import { useState } from "react";
import SbUtils from '../../../Utils/SbUtils'
import SingleControl from './SingleControl'
import ListControl from './ListControl'


const GroupControl = ( { group, groupInd,  editorActiveSection, editorFeedSettings } ) => {

    const [ groupMinimized, setGroupMinimized ] = useState( group?.minimized ? group.minimized : false )

    const attrList = [
        'dimmed'
    ];

    return (

        <div
            className='sb-group-ctn sb-fs'
            data-minimized={ groupMinimized }
            { ...SbUtils.getElementAttributes( group, attrList ) }

        >
            <div
                className='sb-group-heading sb-text-small sb-bold sb-fs'
                onClick={ () => {
                    setGroupMinimized( !groupMinimized )
                }}
            >
                {
                    group.icon &&
                    <div className='sb-group-heading-icon'> { SbUtils.printIcon( group.icon ) } </div>
                }
                {group.heading}
            </div>
            <div className='sb-group-controls-list sb-fs'>
                {
                    group.controls.map( ( element, elementInd ) => {

                        let showElement = SbUtils.checkControlCondition( element, editorFeedSettings.feedSettings );
                        element.dimmed = showElement === 'dimmed' ? true : null;

                        if( element.type === 'list'  && showElement !== false ){
                            /** Render List control*/
                            return (
                                <ListControl
                                    key={ elementInd }
                                    list={ element }
                                    listInd={ elementInd }
                                    fullspace={ element.fullspace }
                                />
                            );
                        }else{
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

    )

}

export default GroupControl;