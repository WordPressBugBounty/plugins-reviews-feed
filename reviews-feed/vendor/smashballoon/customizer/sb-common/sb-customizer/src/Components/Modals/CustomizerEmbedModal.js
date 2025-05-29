import { __ } from "@wordpress/i18n";
import { useState } from "react";
import SbUtils from "../../Utils/SbUtils";
import Button from "../Common/Button";
import ToggleSet from "../Common/ToggleSet";

const CustomizerEmbedModal = ( props ) => {

    const [ activeView, setActiveView ] = useState( 'copy' )
    const [ choosedPageID, setChoosedPageID ] = useState( null )

   const pagesListToggle = props?.sbCustomizer?.wordpressPageLists.map( page => {
       return {
           value: page.id,
           label: page.title.slice(0, 100)
        }
    })

    const views = {
        copy : {
            heading : __( 'Embed Feed', 'sb-customizer' )
        },
        addPage : {
            heading : __( 'Add to Page', 'sb-customizer' ),
            backButton : true
        }
    }

    return (
        <div className='sb-embedfeed-modal sb-fs'>
            <div className='sb-embedfeed-modal-heading sb-fs'>
                {
                    views[activeView].backButton &&
                    <span
                        className='sb-embedfeed-modal-back sb-dark-text sb-text-tiny sb-bold'
                        onClick={ () => {
                                setActiveView('copy')
                        }}
                    >
                        { SbUtils.printIcon( 'chevron-left' ) }
                        { __( 'Embed Feed', 'sb-customizer' ) }
                    </span>
                }
                <h4 className='sb-h4'>{ views[activeView].heading }</h4>
            </div>

            <div className='sb-embedfeed-modal-content sb-fs'>
                {
                    activeView === 'copy' &&
                    <>
                        <div className='sb-fs'>
                            <strong className="sb-fs">{ __( 'Add the unique shortcode to any page, post, or widget:', 'sb-customizer' ) }</strong>
                            <div className='sb-embedfeed-modal-info sb-fs'>
                                <span className='sb-text sb-fs'>[reviews-feed feed={ props.editorFeedData.feedData.feed_info.id }]</span>
                                <Button
                                    size='large'
                                    type='primary'
                                    text={ __( 'Copy', 'sb-customizer' ) }
                                    icon='copy'
                                    onClick={ () => {
                                        SbUtils.copyToClipBoard( `[reviews-feed feed=${ props.editorFeedData.feedData.feed_info.id }]`, props.editorNotification )
                                     }}
                                />
                            </div>
                        </div>
                        <div className='sb-fs'>
                            <strong className="sb-fs">{ __( 'Or use the built in WordPress block or widget', 'sb-customizer' ) }</strong>
                            <div className='sb-embedfeed-modal-addbtns sb-fs'>
                                <Button
                                    fullwidth='true'
                                    size='large'
                                    type='secondary'
                                    boxshadow='false'
                                    text={ __( 'Add to a Page', 'sb-customizer' ) }
                                    icon='addpage'
                                    onClick={ () => {
                                        setActiveView('addPage')
                                    }}
                                />
                                {
                                    props?.sbCustomizer?.themeSupportsWidgets &&
                                    <Button
                                        fullwidth='true'
                                        size='large'
                                        type='secondary'
                                        boxshadow='false'
                                        text={ __( 'Add to a Widget', 'sb-customizer' ) }
                                        icon='addwidget'
                                        onClick={ () => {
                                            const widgetsUrl = `${props.sbCustomizer.widgetsPageURL}`
                                            window.open(widgetsUrl, '_blank');
                                        } }
                                    />
                                }
                            </div>
                        </div>
                    </>
                }

                {
                    activeView === 'addPage' &&
                    <>
                        <div className='sb-fs'>
                            <strong className='sb-embedfeed-modal-pages-head sb-dark2-text sb-fs'>{ __( 'Select Page', 'sb-customizer' ) }</strong>
                            <div className='sb-embedfeed-modal-pageslist sb-fs'>
                                {
                                    props?.sbCustomizer?.wordpressPageLists &&
                                    <ToggleSet
                                        customClass='sb-togglset-control'
                                        options={ pagesListToggle }
                                        value={ choosedPageID }
                                        onClick={ ( pageID ) => {
                                            setChoosedPageID(pageID)
                                        } }
                                    />
                                }
                            </div>
                        </div>
                        <div className='sb-embedfeed-modal-actbtns'>
                            <Button
                                size='medium'
                                type='secondary'
                                text={ __( 'Cancel', 'sb-customizer' ) }
                                onClick={ () => {
                                    setActiveView('copy')
                                }}
                            />
                            <Button
                                size='medium'
                                type='primary'
                                text={ __( 'Add', 'sb-customizer' ) }
                                icon='success'
                                onClick={ () => {
                                    if( choosedPageID !== null ){
                                        const wizzardUrl = `${props.sbCustomizer.adminPostURL + '?post='+choosedPageID+'&action=edit&sbr_wizard=1'}`
                                        window.open(wizzardUrl, '_blank');
                                    }
                                }}
                            />
                        </div>
                    </>

                }
            </div>

        </div>
    )
}
export default CustomizerEmbedModal;