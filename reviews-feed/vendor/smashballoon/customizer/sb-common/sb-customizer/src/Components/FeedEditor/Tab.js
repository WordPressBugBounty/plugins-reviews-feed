import { useContext, useMemo } from 'react'
import SbUtils from '../../Utils/SbUtils';
import FeedEditorContext from '../Context/FeedEditorContext';
import Section from './SidebarElements/Section';

const Tab = () => {

    const { editorActiveTab, editorActiveSection } = useContext( FeedEditorContext )
    const sections = editorActiveTab.activeTab.sections

    return (
        <div className='sb-customizer-sidebar-sections sb-fs'>
            {
                Object.keys( sections ).map( ( secIndex ) => {
                    const section = sections[secIndex];
                    return (
                        <Section
                            key={ secIndex }
                            secIndex={ secIndex }
                            section={ section }
                            editorActiveSection={ editorActiveSection }
                        />
                    )
                })
            }

        </div>
    );
}

export default Tab;