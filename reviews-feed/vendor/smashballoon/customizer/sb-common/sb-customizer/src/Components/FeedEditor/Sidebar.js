import { useContext, useMemo } from 'react'
import SbUtils from '../../Utils/SbUtils'
import FeedEditorContext from '../Context/FeedEditorContext'
import Tab from '../FeedEditor/Tab'
import Section from './SidebarElements/Section'
import GroupControl from './SidebarElements/GroupControl'
import SingleControl from './SidebarElements/SingleControl'
import ListControl from './SidebarElements/ListControl';
import CheckBoxSection from './SidebarElements/CheckBoxSection';
import SidebarCards from './SidebarCards'

const Sidebar = ( ) => {

    const {
        editorActiveTab,
        editorActiveSection,
        sbCustomizer,
        editorFeedSettings,
        editorBreadCrumb,
        editorModerationMode,
        upsellModal,
        currentCardIndex
    } = useContext( FeedEditorContext );
    let sidebarContentOutput = ( ) => {
        /** Tab Sections */
        if( editorActiveSection.activeSection === null ){
            return (
                <div className='sb-customizer-sidebar-content sb-fs'>
                    <div className='sb-customizer-sidebar-tabs sb-fs'>
                        {
                            sbCustomizer.customizerData.map(( tab, tabIndex ) => {
                                return (
                                    <div
                                        className='sb-customizer-sidebar-tab'
                                        key={ tabIndex }
                                        data-active={ editorActiveTab.activeTab.id === tab.id }
                                        onClick={ () => {
                                                editorActiveTab.setActiveTab( tab )
                                            }
                                        }
                                    >
                                        <span className='sb-standard-p sb-bold'>{ tab.name }</span>
                                    </div>
                                )
                            })
                        }
                    </div>
                    <Tab />
                    <SidebarCards
                        currentCardIndex={currentCardIndex}
                    />
                </div>
            );
        }else{
            /** Section Controls */
            let section = editorActiveSection.activeSection;
            const isSettingPro = SbUtils.checkSettingIsPro( section.upsellModal );
            return (
                <div className='sb-fs'>
                    <div className='sb-customizer-sidebar-header sb-fs'>
                        <div className='sb-customizer-sidebar-breadcrumbs sb-fs'>
                            {
                                ( editorBreadCrumb.breadCrumb === null || editorBreadCrumb.breadCrumb.length < 2 ) &&
                                <span
                                    className='sb-customizer-breadcrumbs-elm'
                                    onClick={ ( ) => {
                                        editorActiveSection.setActiveSection( null )
                                        editorBreadCrumb.setBreadCrumb( null )
                                        editorModerationMode.setModerationMode( false );
                                    } }
                                >
                                    { editorActiveTab.activeTab.name }
                                </span>
                            }
                            {
                                editorBreadCrumb.breadCrumb !== null &&
                                editorBreadCrumb.breadCrumb.map( (br, brInd) =>  {

                                    //Small logic to check what BreadCrumb Links to show
                                    const checkDisplayBrLink = ( editorBreadCrumb.breadCrumb.length <= 2 ||
                                        editorBreadCrumb.breadCrumb.length + brInd > editorBreadCrumb.breadCrumb.length)

                                    if( checkDisplayBrLink ){
                                        return (
                                            <span
                                                className='sb-customizer-breadcrumbs-elm'
                                                key={ brInd }
                                                onClick={ ( ) => {
                                                    editorActiveSection.setActiveSection( br )
                                                    editorBreadCrumb.breadCrumb.splice( brInd );
                                                } }
                                            >
                                                { br.heading }
                                            </span>
                                        )
                                    }
                                } )
                            }
                        </div>

                        <h3>
                            { section.heading }
                            { SbUtils.printProLabel( isSettingPro ) }
                        </h3>

                        {
                            section.description &&
                            <span
                                className='sb-small-p sb-dark-text'
                                dangerouslySetInnerHTML={{__html: section.description }}
                                onClick={ () => {
                                    if( section?.upsellModal ){
                                        upsellModal.setUpsellActive( section?.upsellModal )
                                    }
                                } }
                            ></span>
                        }
                    </div>
                    <div className='sb-customizer-sidebar-controls-ctn sb-fs'>
                        {
                            section.controls.map( ( element, elementInd ) => {
                                let showElement = SbUtils.checkControlCondition( element, editorFeedSettings.feedSettings );
                                element.dimmed = showElement === 'dimmed' ? true : null;

                                if( element.type === 'section' && showElement !== false ){
                                        /** Render nested sections*/
                                        elementInd = element.id;
                                        return (
                                            <Section
                                                key={ elementInd }
                                                section={ element }
                                                secIndex={ elementInd }
                                                editorActiveSection={ editorActiveSection }
                                                editorBreadCrumb={ editorBreadCrumb }
                                                parentSection={ section }
                                            />
                                        );
                                }else if( element.type === 'group'  && showElement !== false ){
                                    /** Render Group control*/
                                    return (
                                        <GroupControl
                                            key={ elementInd }
                                            group={ element }
                                            groupInd={ elementInd }
                                            editorFeedSettings={editorFeedSettings}
                                        />
                                    );
                                }else if( element.type === 'list'  && showElement !== false ){
                                    /** Render List control*/
                                    return (
                                        <ListControl
                                            key={ elementInd }
                                            list={ element }
                                            listInd={ elementInd }
                                            fullspace={ element.fullspace }
                                        />
                                    );
                                } else if( element.type === 'checkboxsection'  && showElement !== false ){
                                    /** Render CheckBoxSection control*/
                                    return (
                                        <CheckBoxSection
                                            key={ elementInd }
                                            checkBoxSection={ element }
                                            CheckBoxSectionInd={ elementInd }
                                            editorBreadCrumb={ editorBreadCrumb }
                                            parentSection={ section }
                                            upsellModal={ upsellModal }
                                        />
                                    );
                                } else if( element.type === 'separator'  && showElement !== false ){
                                    /** Render Separator control*/
                                    return (
                                        <div
                                            className='sb-separator-ctn sb-fs'
                                            style={{ marginTop : element.top +'px', marginBottom : element.bottom +'px' }}
                                            key={ elementInd }
                                        ></div>
                                    );
                                } else{
                                    if( showElement !== false ){
                                        /** Render normal control*/
                                        return (
                                            <SingleControl
                                                key={ elementInd }
                                                control={ element }
                                                controlInd={ elementInd }
                                            />
                                        );
                                    }

                                }
                            })
                        }
                    </div>
                </div>
            );
        }
    }

    return (
        <section className='sb-customizer-sidebar'>
            { sidebarContentOutput() }
        </section>
    );

}

export default Sidebar;